import React, { Children } from 'react';
import { motion } from 'framer-motion';
import { CalendarDays, Store, Users, RefreshCw } from 'lucide-react';
export function CoreFeatures() {
  const features = [
  {
    icon: <CalendarDays size={32} />,
    title: 'Visual Calendar',
    description:
    'Monthly view with daily counts, and a detailed hourly timeline for each day.'
  },
  {
    icon: <Store size={32} />,
    title: 'Multi-Restaurant',
    description:
    'Manage all your locations from one admin account, and switch between them instantly.'
  },
  {
    icon: <Users size={32} />,
    title: 'Staff Management',
    description:
    'Add unlimited staff accounts with role-based access. Keep settings secure.'
  },
  {
    icon: <RefreshCw size={32} />,
    title: 'Real-Time Updates',
    description:
    'Changes sync automatically across all devices. No page refresh needed.'
  }];

  const container = {
    hidden: {
      opacity: 0
    },
    show: {
      opacity: 1,
      transition: {
        staggerChildren: 0.1
      }
    }
  };
  const item = {
    hidden: {
      opacity: 0,
      y: 20
    },
    show: {
      opacity: 1,
      y: 0,
      transition: {
        duration: 0.5
      }
    }
  };
  return (
    <section id="features" className="py-24 bg-cream">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center max-w-3xl mx-auto mb-16">
          <h2 className="text-3xl md:text-4xl font-bold text-forest mb-4">
            Everything you need to manage reservations
          </h2>
          <p className="text-lg text-forest/70">
            Included in all plans. No hidden fees, no complex setup.
          </p>
        </div>

        <motion.div
          variants={container}
          initial="hidden"
          whileInView="show"
          viewport={{
            once: true
          }}
          className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
          
          {features.map((feature, index) =>
          <motion.div
            key={index}
            variants={item}
            className="bg-white p-8 rounded-2xl border border-sage-light shadow-sm hover:shadow-md transition-shadow">
            
              <div className="text-terracotta mb-6 bg-terracotta/10 w-16 h-16 rounded-xl flex items-center justify-center">
                {feature.icon}
              </div>
              <h3 className="text-xl font-bold text-forest mb-3">
                {feature.title}
              </h3>
              <p className="text-forest/70 leading-relaxed">
                {feature.description}
              </p>
            </motion.div>
          )}
        </motion.div>
      </div>
    </section>);

}